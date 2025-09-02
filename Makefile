#!/usr/bin/make
# makefile for cap-rel laravel projects
#
SHELL = bash

$(info using common Makefile)
include Makefile.dist

ifneq ("$(wildcard Makefile.local)","")
  $(info using Makefile.local)
  include Makefile.local
endif
