#!/usr/bin/env bash
set -e
S=/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad
W=$S/pack/web-dist
SRC=/home/user/DOUGHBOSSV2/doughboss-growth-box
cp $SRC/INSTALL.md $W/INSTALL-catering-box.md
rm -f $W/doughboss-growth-box-0.2.0-source.zip
(cd /home/user/DOUGHBOSSV2 && zip -q -r -X $W/doughboss-growth-box-0.2.0-source.zip doughboss-growth-box -x '*.zip' -x '*/.*')
for f in doughboss-growth-box-0.2.0.zip doughboss-growth-media-0.2.0.zip doughboss-growth-box-0.2.0-source.zip; do
  printf '%-48s %9d bytes  sha256 %s\n' $f $(stat -c %s $W/$f) $(sha256sum $W/$f | cut -d' ' -f1)
done
echo; echo "code zip contents:"; unzip -l $W/doughboss-growth-box-0.2.0.zip | tail -n +4 | head -30
echo; echo "media zip hero folder:"; unzip -l $W/doughboss-growth-media-0.2.0.zip | grep -E "assets/hero|\.php|readme"
