.PHONY: all clean install rpm doc deploy rpm-install

ifeq ($(VERSION),)
	VERSION = *DEVELOPMENT SNAPSHOT*
endif

ARCH = noarch
BINDIR ?= /usr/bin
MANDIR ?= /usr/share/man
KEYID ?= BE5096C665CA4595AF11DAB010CD9FF74E4565ED
ARCH_RPM_NAME := bmw-token-manager.$(ARCH).rpm

all: bmw-token-manager.php

doc: bmw-token-manager.1

rpm: $(ARCH_RPM_NAME)

rpm-install: rpm
	zypper in "./$(ARCH_RPM_NAME)"

clean:
	rm -vf -- bmw-token-manager.1 *.rpm

bmw-token-manager.1: README.md Makefile
	go-md2man < README.md > bmw-token-manager.1

install: bmw-token-manager.1 bmw-token-manager.php Makefile
	mkdir -p "$(BINDIR)" "$(MANDIR)/man1" "$(CONFDIR)" "$(UNITDIR)"
	install -m 755 bmw-token-manager.php "$(BINDIR)/"
	install -m 644 bmw-token-manager.1 "$(MANDIR)/man1/"
	install -m 644 bmw-token-manager.conf "$(CONFDIR)/"
	install -m 644 bmw-token-manager@.service "$(UNITDIR)/"

deploy: $(ARCH_RPM_NAME)
	ensure-git-clean.sh
	deploy-rpm.sh --infile=bmw-token-manager.src.rpm --outdir="$(RPMDIR)" --keyid="$(KEYID)" --srpm
	deploy-rpm.sh --infile="$(ARCH_RPM_NAME)" --outdir="$(RPMDIR)" --keyid="$(KEYID)"

$(ARCH_RPM_NAME) bmw-token-manager.src.rpm: Makefile bmw-token-manager.spec README.md LICENSE.md bmw-token-manager.php bmw-token-manager.conf bmw-token-manager@.service
	easy-rpm.sh --name bmw-token-manager --outdir . --plain --arch "$(ARCH)" -- $^
