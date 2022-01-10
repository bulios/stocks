REPO:=martkcz/php-nginx-alpine
VERSION:=8.0.14-r1

all: build release

build:
	docker build -t $(REPO):${VERSION} .

release:
	docker push $(REPO):${VERSION}
