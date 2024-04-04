#!/usr/bin/make
# makefile for cap-rel laravel projects
#
SHELL = bash

ifneq ("$(wildcard Makefile.local)","")
  $(info using Makefile.local)
  include Makefile.local
else
  $(info using common Makefile)
  include Makefile.dist
endif
