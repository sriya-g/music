#!/usr/bin/env bash
#
# Nextcloud Music app
#
# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
#
# @author Pauli Järvinen <pauli.jarvinen@gmail.com>
# @copyright Pauli Järvinen 2025, 2026
#

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <nextcloud_version>"
    exit 1
fi

VERSION=$1

mkdir -p /tmp/nc_music_ci
cd /tmp/nc_music_ci

# download the cloud and setup folders
if [[ $VERSION == *"beta"* || $VERSION == *"rc"* ]]; then
    URL=https://download.nextcloud.com/server/prereleases
else
    URL=https://download.nextcloud.com/server/releases
fi

wget $URL/nextcloud-$VERSION.zip
unzip nextcloud-$VERSION.zip
mv nextcloud server
