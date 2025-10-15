Name:           bmw-token-manager
Summary:        Small PHP 8+ daemon that keeps a BMW OAuth token fresh
Group:          Applications/Databases
Distribution:   openSUSE
License:        GPLv3
URL:            https://www.brennecke-it.net
BuildArch:      noarch
BuildRequires:  go-md2man easy-rpm
Requires:       php-cli

%description
Small PHP 8+ daemon that keeps a BMW OAuth token fresh.

%prep
%setup -q -n bmw-token-manager

%build
make %{?_smp_mflags} VERSION="Version %{version}"

%install
make install CONFDIR=%{buildroot}%{_sysconfdir} BINDIR=%{buildroot}%{_bindir} MANDIR="%{buildroot}%{_mandir}" UNITDIR="%{buildroot}%{_unitdir}"

%files
%{_bindir}/%{name}.php
%{_mandir}/man1/%{name}.1.gz
%{_unitdir}/%{name}@.service
%config(noreplace) %{_sysconfdir}/%{name}.conf

%changelog
