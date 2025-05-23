<?php declare(strict_types=1);

namespace App\Controller;

use App\Entity\Contest;
use App\Entity\ContestProblem;
use App\Entity\Team;
use App\Entity\TeamCategory;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\ScoreboardService;
use App\Service\StatisticsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

#[Route(path: '/public')]
class PublicController extends BaseController
{
    public function __construct(
        protected readonly DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        protected readonly ScoreboardService $scoreboardService,
        protected readonly StatisticsService $stats,
        protected readonly EntityManagerInterface $em
    ) {
    }

    #[Route(path: '', name: 'public_index')]
    #[Route(path: '/scoreboard')]
    public function scoreboardAction(
        Request $request,
        #[MapQueryParameter(name: 'contest')]
        ?string $contestId = null,
        #[MapQueryParameter]
        ?bool $static = false,
    ): Response {
        $response = new Response();
        $refreshUrl = $this->generateUrl('public_index');
        $contest = $this->dj->getCurrentContest(onlyPublic: true);
        $nonPublicContest = $this->dj->getCurrentContest(onlyPublic: false);
        if (!$contest && $nonPublicContest && $this->em->getRepository(TeamCategory::class)->count(['allow_self_registration' => 1])) {
            // This leaks a little bit of information about the existence of the non-public contest,
            // but since self registration is enabled, it's not a big deal.
            return $this->redirectToRoute('register');
        }


        if ($static) {
            $refreshParams = [
                'static' => 1,
            ];

            if ($requestedContest = $this->getContestFromRequest($contestId)) {
                $contest = $requestedContest;
                $refreshParams['contest'] = $contest->getCid();
            }

            $refreshUrl = sprintf('?%s', http_build_query($refreshParams));
        }

        $data = $this->scoreboardService->getScoreboardTwigData(
            $request,
            $response,
            $refreshUrl,
            false,
            true,
            $static,
            $contest
        );

        if ($static) {
            $data['hide_menu'] = true;
        }

        $data['current_contest'] = $contest;

        if ($request->isXmlHttpRequest()) {
            return $this->render('partials/scoreboard.html.twig', $data, $response);
        }
        return $this->render('public/scoreboard.html.twig', $data, $response);
    }

    #[Route(path: '/scoreboard-zip/contest.zip', name: 'public_scoreboard_data_zip')]
    public function scoreboardDataZipAction(
        RequestStack $requestStack,
        Request $request,
        #[MapQueryParameter(name: 'contest')]
        ?string $contestId = null
    ): Response {
        $contest = $this->getContestFromRequest($contestId) ?? $this->dj->getCurrentContest(onlyPublic: true);
        return $this->dj->getScoreboardZip($request, $requestStack, $contest, $this->scoreboardService);
    }

    /**
     * Get the contest from the request, if any
     */
    protected function getContestFromRequest(?string $contestId = null): ?Contest
    {
        $contest = null;
        // For static scoreboards, allow to pass a contest= param.
        if ($contestId) {
            if ($contestId === 'auto') {
                // Automatically detect the contest that is activated the latest.
                $activateTime = null;
                foreach ($this->dj->getCurrentContests(onlyPublic: true) as $possibleContest) {
                    if (!($possibleContest->getPublic() && $possibleContest->getEnabled())) {
                        continue;
                    }
                    if ($activateTime === null || $activateTime < $possibleContest->getActivatetime()) {
                        $activateTime = $possibleContest->getActivatetime();
                        $contest = $possibleContest;
                    }
                }
            } else {
                // Find the contest with the given ID.
                foreach ($this->dj->getCurrentContests(onlyPublic: true) as $possibleContest) {
                    if ($possibleContest->getCid() == $contestId || $possibleContest->getExternalid() == $contestId) {
                        $contest = $possibleContest;
                        break;
                    }
                }

                if (!$contest) {
                    throw new NotFoundHttpException('Specified contest not found.');
                }
            }
        }

        return $contest;
    }

    #[Route(path: '/change-contest/{contestId<-?\d+>}', name: 'public_change_contest')]
    public function changeContestAction(Request $request, RouterInterface $router, int $contestId): Response
    {
        if ($this->isLocalReferer($router, $request)) {
            $response = new RedirectResponse($request->headers->get('referer'));
        } else {
            $response = $this->redirectToRoute('public_index');
        }
        return $this->dj->setCookie(
            'domjudge_cid',
            (string) $contestId,
            0,
            null,
            '',
            false,
            false,
            $response
        );
    }

    #[Route(path: '/team/{teamId<\d+>}', name: 'public_team')]
    public function teamAction(Request $request, int $teamId): Response
    {
        /** @var Team|null $team */
        $team = $this->em->getRepository(Team::class)->find($teamId);
        if ($team && $team->getCategory() && !$team->getCategory()->getVisible()) {
            $team = null;
        }
        $showFlags = (bool) $this->config->get('show_flags');
        $showAffiliations = (bool) $this->config->get('show_affiliations');
        $data = [
            'team' => $team,
            'showFlags' => $showFlags,
            'showAffiliations' => $showAffiliations,
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('public/team_modal.html.twig', $data);
        }

        return $this->render('public/team.html.twig', $data);
    }

    /**
     * @throws NonUniqueResultException
     */
    #[Route(path: '/problems', name: 'public_problems')]
    public function problemsAction(): Response
    {
        return $this->render(
            'public/problems.html.twig',
            $this->dj->getTwigDataForProblemsAction($this->stats)
        );
    }

    #[Route(path: '/problems/{probId<\d+>}/statement', name: 'public_problem_statement')]
    public function problemStatementAction(int $probId): StreamedResponse
    {
        return $this->getBinaryFile($probId, function (int $probId, Contest $contest, ContestProblem $contestProblem) {
            $problem = $contestProblem->getProblem();

            try {
                return $problem->getProblemStatementStreamedResponse();
            } catch (BadRequestHttpException $e) {
                $this->addFlash('danger', $e->getMessage());
                return $this->redirectToRoute('public_problems');
            }
        });
    }

    #[Route(path: '/problemset', name: 'public_contest_problemset')]
    public function contestProblemsetAction(): StreamedResponse
    {
        $contest = $this->dj->getCurrentContest(onlyPublic: true);
        if (!$contest->getFreezeData()->started()) {
            throw new NotFoundHttpException('Contest problemset not found or not available');
        }
        return $contest->getContestProblemsetStreamedResponse();
    }

    #[Route(path: '/about', name: 'public_about')]
    public function About(): Response
    {
        $organizers = [
            [
                'name' => 'Juan Pablo Ospina',
                'title' => 'Ingeniero de Sistemas | Magíster en Ingeniería de Sistemas y Computación | Doctor en Ingeniería de Sistemas y Computación',
                'image' => 'images/organizators/Juan.png',
                'github' => 'https://github.com',
                'info' => 'https://www.usergioarboleda.edu.co/escuela-de-ciencias-exactas-e-ingenieria/Sobre-la-Escuela/',
                'period' => '2022-2025',
            ],
            [
                'name' => 'Andres Santiago Ducuara Velasquez',
                'title' => 'Ingeniero en Ciencias de la Computación e Inteligencia Artificial',
                'image' => 'images/organizators/ducuara.jpg',
                'github' => 'https://github.com/AndresTY',
                'info' => 'https://andresty.github.io/Website/html/Ducu.html',
                'period' => '2022-2025',
            ],
            [
                'name' => 'Santiago Canchila',
                'title' => 'Ingeniero en Ciencias de la Computación e Inteligencia Artificial',
                'image' => 'images/organizators/scc.jpeg',
                'github' => 'https://github.com/scanchila',
                'info' => 'https://scanchila.github.io/dev-website',
                'period' => '2022-2025',
            ],
            [
                'name' => 'Camilo José Salazar Palacio',
                'title' => 'Ingeniero Electrónico |Especialista en telecomunicaciones | Magíster en telecomunicaciones y regulación TIC',
                'image' => 'images/organizators/camilo.png',
                'github' => 'https://github.com',
                'info' => 'https://www.usergioarboleda.edu.co/escuela-de-ciencias-exactas-e-ingenieria/Sobre-la-Escuela/',
                'period' => '2025',
            ],
            [
                'name' => 'Luis Alejandro Angel Ácosta',
                'title' => 'Ingeniero Electrónico |  Magíster en Ingeniería Industrial – Dirección y Gestión Organizacional | Executive MBA con grado Cum Laude',
                'image' => 'images/organizators/luis.png',
                'github' => 'https://github.com/aclxrd/',
                'info' => 'https://www.usergioarboleda.edu.co/escuela-de-ciencias-exactas-e-ingenieria/Sobre-la-Escuela/',
                'period' => '2022-2024',
            ],
            [
                'name' => 'Valerie Gutiérrez Zambrano',
                'title' => 'Estudiantes de ciencias de la computación e inteligencia artificial - sem 1', 
                'image' => 'images/organizators/default.jpg',
                'github' => 'https://github.com',
                'info' => 'https://www.usergioarboleda.edu.co/escuela-de-ciencias-exactas-e-ingenieria/Sobre-la-Escuela/',
                'period' => '2025',
            ],
             [
                'name' => 'Juan Felipe Gonzalez Barrera',
                'title' => 'Estudiantes de ciencias de la computación e inteligencia artificial - sem 1', 
                'image' => 'images/organizators/default.jpg',
                'github' => 'https://github.com',
                'info' => 'https://www.usergioarboleda.edu.co/escuela-de-ciencias-exactas-e-ingenieria/Sobre-la-Escuela/',
                'period' => '2025',
            ],
        ];
        $c1 = 'Andres Ducuara';
        $c2 = 'Santiago Canchila';

        return $this->render('public/about.html.twig', [
            'creator1' => $c1,
            'creator2' => $c2,
            'organizers' => $organizers,
        ]);
    }

    #[Route(path: '/organizer2', name: 'public_organizer')]
    public function About_Org2(): Response
    {
        $orga2 = [
            [
                'name' => 'Santiago Pérez González',
                'title' => 'Ingeniero de Sistemas y Telecomunicaciones | Software Engineer ',
                'image' => 'images/organizators/Santi.jpg',
                'github' => 'https://github.com/SunTea43',
                'info' => 'https://www.linkedin.com/in/santiago-p%C3%A9rez-gonz%C3%A1lez-abb49b144/',
                'Apoyos' => [
                    ['Period' => '2022', 'do' => 'Monstaje de la pagina web'],
                    ['Period' => '2023', 'do' => 'Apoyo en el monstaje de la pagina web'],
                    ['Period' => '2024', 'do' => 'Desarrollo de los problemas'],
                ]
            ],
            [
                'name' => 'Andrés C. López R.',
                'title' => 'Ingeniero de Sistemas y Telecomunicaciones | Desarrollo de Software',
                'image' => 'images/organizators/Andres.jpg',
                'github' => 'https://github.com/aclxrd',
                'info' => 'https://www.linkedin.com/in/aclr',
                'Apoyos' => [
                    ['Period' => '2022', 'do' => 'Monstaje de la pagina web'],
                    ['Period' => '2023', 'do' => 'Apoyo en el monstaje de la pagina web'],
                    ['Period' => '2024', 'do' => 'Desarrollo de los problemas'],
                ]
            ],
            [
                'name' => 'Valentina Del Pilar Franco Suárez',
                'title' => 'Ingeniera en ciencias de la computacion e inteligencia artificial',
                'image' => 'images/organizators/vale.jpg',
                'github' => 'https://github.com/valentinafranco',
                'info' => 'https://www.linkedin.com/in/valentina-del-pilar-franco-su%C3%A1rez-24b11b175',
                'Apoyos' => [
                    ['Period' => '2023', 'do' => 'Personera estudantil del programa de Ciencias de la computacion e inteligencia artificial'],
                ]
            ],
            [
                'name' => 'Juan cortes ',
                'title' => 'Ingeniero en ciencias de la computacion e inteligencia artificial',
                'image' => 'images/organizators/default.jpg',
                'github' => 'www.github.com',
                'info' => 'https://www.usergioarboleda.edu.co/',
                'Apoyos' => [
                    ['Period' => '2024', 'do' => 'Personera estudantil del programa de Ciencias de la computacion e inteligencia artificial'],
                ]
            ],

        ];
        $c1 = 'Andres Ducuara';
        $c2 = 'Santiago Canchila';

        return $this->render('public/orga2.html.twig', [
            'creator1' => $c1,
            'creator2' => $c2,
            'orga2' => $orga2,
        ]);
    }

    #[Route(path: '/winners', name: 'public_winners')]
    public function About_Winners(): Response
    {
        $winners = [
            [
                'team' => 'Artic Munkres',
                'year' => '2024-2',
                'image' => 'images/winners/maraton20242.jpg',
                'link' => 'https://www.usergioarboleda.edu.co/noticias/retos-talento-y-programacion-asi-fue-la-maraton-de-programacion-2024-2',
                'members' => [
                    ['name' => 'Juan Sebastián Caballero Bernal', 'career' => 'Math', 'semester' => '7 sem'],
                    ['name' => 'Santiago Garcia Rincón', 'career' => 'Math', 'semester' => '10 sem'],
                    ['name' => 'Sergio Andres Caceres Diaz', 'career' => 'Math', 'semester' => '7 sem']
                ]
            ],
            [
                'team' => 'Ácidos Carboxilicos',
                'year' => '2024-1',
                'image' => 'images/winners/maraton20241.jpg',
                'link' => 'https://www.usergioarboleda.edu.co/noticias/sergistas-demostraron-sus-habilidades-en-python-y-java-en-la-quinta-edicion-de-la-maraton-de-programacion/',
                'members' => [
                    ['name' => 'Daniel Santiago Varela Guerrero', 'career' => 'Ciencias', 'semester' => '5 sem'],
                    ['name' => 'Tomas De Andreis Rojas', 'career' => 'Ciencias', 'semester' => '5 sem'],
                    ['name' => 'Gustavo Takashi Cabrera Rosales', 'career' => 'Ciencias', 'semester' => '5 sem']
                ]
            ],
            [
                'team' => 'El abuelo de Christian',
                'year' => '2023-2',
                'image' => 'images/winners/maraton20232.webp',
                'link' => 'https://www.usergioarboleda.edu.co/noticias/sergistas-brillan-en-maraton-de-programacion/',
                'members' => [
                    ['name' => 'Juan Sebastián Caballero Bernal', 'career' => 'Math', 'semester' => '5 sem'],
                    ['name' => 'Santiago Garcia Rincón', 'career' => 'Math', 'semester' => '10 sem'],
                    ['name' => 'Javier Camilo Torres Peña', 'career' => 'Math', 'semester' => '7 sem']
                ]
            ],
            [
                'team' => 'ZEUS',
                'year' => '2023-1',
                'image' => 'images/winners/maraton20231.webp',
                'link' => '',
                'members' => [
                    ['name' => 'Daniel Andres Piamba Escobar', 'career' => 'Math', 'semester' => '9 sem'],
                    ['name' => 'Jose Efren Muñoz Munevar', 'career' => 'Math', 'semester' => '10 sem'],
                    ['name' => 'Santiago  Garcia Rincón', 'career' => 'Math', 'semester' => '9 sem']
                ]
            ],
            [
                'team' => 'Fibonacci de X',
                'year' => '2022-2',
                'image' => 'images/winners/maraton20222.webp.jpg',
                'link' => '',
                'members' => [
                    ['name' => 'Felipe Parra Castro', 'career' => 'Math', 'semester' => '- sem'],
                    ['name' => 'Henry Mauricio Cañón Cortés', 'career' => 'Math', 'semester' => '- sem'],
                    ['name' => 'Johan Andrés López Botero', 'career' => 'Math', 'semester' => '- sem']
                ]
            ],
            [
                'team' => 'Javalimos',
                'year' => '2022-1',
                'image' => 'images/winners/maraton20221.webp',
                'link' => '',
                'members' => [
                    ['name' => 'Jose Andres Diaz Naranjo', 'career' => 'Ciencias', 'semester' => '10 sem'],
                    ['name' => 'Camilo Hernandez', 'career' => 'Ciencias', 'semester' => '7 sem'],
                    ['name' => 'Juan Esteban Arias', 'career' => 'Ciencias', 'semester' => '7 sem']
                ]
            ],
        ];
        $c1 = 'Andres Ducuara';
        $c2 = 'Santiago Canchila';

        return $this->render('public/winners.html.twig', [
            'creator1' => $c1,
            'creator2' => $c2,
            'winners' => $winners,
        ]);
    }

    /**
     * @throws NonUniqueResultException
     */
    #[Route(path: '/{probId<\d+>}/attachment/{attachmentId<\d+>}', name: 'public_problem_attachment')]
    public function attachmentAction(int $probId, int $attachmentId): StreamedResponse
    {
        return $this->getBinaryFile($probId, fn(
            int $probId,
            Contest $contest,
            ContestProblem $contestProblem
        ) => $this->dj->getAttachmentStreamedResponse($contestProblem, $attachmentId));
    }

    #[Route(path: '/{probId<\d+>}/samples.zip', name: 'public_problem_sample_zip')]
    public function sampleZipAction(int $probId): StreamedResponse
    {
        return $this->getBinaryFile($probId, function (int $probId, Contest $contest, ContestProblem $contestProblem) {
            return $this->dj->getSamplesZipStreamedResponse($contestProblem);
        });
    }

    /**
     * Get a binary file for the given problem ID using the given callable.
     *
     * Shared code between testcases, problem text and attachments.
     */
    protected function getBinaryFile(int $probId, callable $response): StreamedResponse
    {
        $contest = $this->dj->getCurrentContest(onlyPublic: true);
        if (!$contest || !$contest->getFreezeData()->started()) {
            throw new NotFoundHttpException(sprintf('Problem p%d not found or not available', $probId));
        }
        $contestProblem = $this->em->getRepository(ContestProblem::class)->find([
            'problem' => $probId,
            'contest' => $contest,
        ]);
        if (!$contestProblem) {
            throw new NotFoundHttpException(sprintf('Problem p%d not found or not available', $probId));
        }

        return $response($probId, $contest, $contestProblem);
    }
}
