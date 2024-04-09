FROM domjudge/domserver:latest

COPY ./templates /opt/domjudge/domserver/webapp/templates
COPY ./public /opt/domjudge/domserver/webapp/public