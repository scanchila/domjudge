## Domjudge Image

This repository contains the Dockerization of Domjudge, tailored for the internal programming marathon at Universidad Sergio Arboleda. It has been customized with institutional colors and logos, along with enhanced user experience in the login section. Additionally, an "About" section has been added to showcase the organizers alongside the latest winners.

## Deployment

### Build and run Docker container
```shell
make build
```

### Run Docker container
```shell
make up
```

### Stop Docker container
```shell
make down
```

### Show logs
```shell
make show-logs
```

# EC2 Configuration
- Type: t3.large
- Operating System: Ubuntu LTS
- Volume: 8 GB root (default) - 20 GB gd3 (additional)
- Enabled ports: 433, 80, 22
- Elastic IP
- Domain

# Docker Installation
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

# Docker Configuration
```shell
sudo groupadd docker
sudo usermod -aG docker $USER
newgrp docker
```

# Build and Run Docker
```shell
make build
docker compose up (dev)
```
