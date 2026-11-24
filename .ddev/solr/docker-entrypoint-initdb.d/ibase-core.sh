#!/usr/bin/env bash
# Second Solr core, "ibase", for the ibase multisite, so its private
# insurance documents never share a core with dyss.net's public index.
# precreate-core does nothing if the core already exists; afterwards the
# config is refreshed on every start, like solr-configupdate.sh does for "dev".
set -e
if [ -d /var/solr/data/ibase/conf ]; then
  cp /solr-conf/conf/* /var/solr/data/ibase/conf
else
  /opt/docker-solr/scripts/precreate-core ibase /solr-conf
fi
