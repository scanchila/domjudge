FROM domjudge/domserver:latest


COPY ./webapp/public/images/ /opt/domjudge/domserver/webapp/public/images
COPY ./webapp/public/js/egg.min.js /opt/domjudge/domserver/webapp/public/js
COPY ./webapp/public/style_domjudge.css /opt/domjudge/domserver/webapp/public/style_domjudge.css
COPY ./webapp/public/style_login.css /opt/domjudge/domserver/webapp/public/style_login.css
COPY ./webapp/src/Controller/PublicController.php /opt/domjudge/domserver/webapp/src/Controller/PublicController.php
COPY ./webapp/templates/public/about.html.twig /opt/domjudge/domserver/webapp/templates/public/about.html.twig
COPY ./webapp/templates/public/base.html.twig /opt/domjudge/domserver/webapp/templates/public/base.html.twig
COPY ./webapp/templates/public/menu.html.twig /opt/domjudge/domserver/webapp/templates/public/menu.html.twig
COPY ./webapp/templates/security/login.html.twig /opt/domjudge/domserver/webapp/templates/security/login.html.twig
COPY ./webapp/templates/team/base.html.twig /opt/domjudge/domserver/webapp/templates/team/base.html.twig
COPY ./webapp/templates/team/menu.html.twig /opt/domjudge/domserver/webapp/templates/team/menu.html.twig
COPY ./webapp/templates/base.html.twig /opt/domjudge/domserver/webapp/templates/base.html.twig 