## Imagen de Domjudge

Este repositorio contiene la dockerización de Domjudge, adaptado para la maratón de programación interna de la Universidad Sergio Arboleda. Se ha personalizado con los colores y logotipos institucionales, además de mejorar la experiencia de usuario en la sección de inicio de sesión. También se ha añadido una sección "Acerca de" donde se muestran los organizadores junto con los últimos ganadores.

## Despliegue

### Construir y ejecutar el contenedor Docker
```shell
make build
```

### Ejecutar el contenedor Docker
```shell
make up
```

### Detener el contenedor Docker
```shell
make down
```

### Mostrar los registros
```shell
make show-logs
```

# Configuración de EC2
- Tipo: t3.large
- Sistema operativo: Ubuntu LTS
- Volumen: 8 GB de root (predeterminado) - 20 GB de gd3 (adicional)
- Puertos habilitados: 433, 80, 22
- IP elástica
- Dominio

# Instalación de Docker
```shell
sudo apt update
sudo apt install apt-transport-https ca-certificates curl software-properties-common
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo apt-key add -
sudo add-apt-repository "deb [arch=amd64] https://download.docker.com/linux/ubuntu focal stable"
sudo apt update
apt-cache policy docker-ce
sudo apt install docker-ce
sudo systemctl status docker
```

# Configuración de Docker
```shell
sudo groupadd docker
sudo usermod -aG docker $USER
newgrp docker
```

# Construir y ejecutar Docker
```shell
make build
docker compose up (dev)
```
