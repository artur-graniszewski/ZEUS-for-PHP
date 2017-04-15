.PHONY : doc-server public-doc-server doc-build

doc-server :
	mkdocs doc-server

public-doc-server :
	mkdocs serve --dev-addr=0.0.0.0:8080

doc-build :
	mkdocs build