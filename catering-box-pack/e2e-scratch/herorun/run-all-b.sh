#!/usr/bin/env bash
# Full clean run on core B (2.41.0): fresh runtime, phases A, B, C, D, then contrast.
cd /tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/herorun
rm -rf out shots && mkdir -p out shots
./rebuild.sh > out/rebuild.log 2>&1 || { echo REBUILD FAILED; cat out/rebuild.log; exit 1; }
for ph in phase-admin phase-visit phase-matrix phase-kill; do
  echo "=== $ph" ; node $ph.mjs > out/$ph.out 2>&1; echo "exit $?" >> out/$ph.out; tail -3 out/$ph.out
done
for s in "d768:768x1024" "d1024:1024x768" "d1280:1280x720" "d1440:1440x900" "d1920:1920x1080" "phone:390x844x2"; do node contrast_capture.mjs ${s%%:*} ${s##*:} 0.5,2,3.5,5,6.5,8,9.5 >> out/contrast-capture.out 2>&1; done
python3 contrast_analyse.py d768 d1024 d1280 d1440 d1920 phone > out/contrast-analysis.txt 2>&1
echo ALL DONE > out/done.flag
