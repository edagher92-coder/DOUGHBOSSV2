#!/usr/bin/env bash
# rebuild code zip + media zip, restart the runtime
set -e
S=/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad
rm -f $S/pack/web-dist/doughboss-growth-box-0.1.0.zip
php /home/user/DOUGHBOSSV2/doughboss-growth-box/scripts/build-zip.php $S/pack/web-dist/doughboss-growth-box-0.1.0.zip
(cd $S/pack/web-dist && python3 build_media_zip.py)
export WPL_STATE=/tmp/wp-local-box WPL_PG_DIR=/tmp/wp-local/pg WPL_PORT=9411 WPL_BOX_ZIP_CODE=$S/pack/web-dist/doughboss-growth-box-0.1.0.zip WPL_BOX_ZIP_MEDIA=$S/pack/web-dist/doughboss-growth-media-0.1.0.zip
bash /home/user/DOUGHBOSSV2/web/scripts/wp-local/stop.sh >/dev/null || true
cd $S/boxrun && bash start.sh
