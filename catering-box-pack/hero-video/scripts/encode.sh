# Encode the baked ping-pong loop: H.264 (fallback) + AV1 (primary), 1080x1920 and 720x1280, plus posters.
set -e
cd "$(dirname "$0")"
F=frames/%04d.png
ffmpeg -y -loglevel error -framerate 24 -i $F -c:v libx264 -preset slow -crf 19 -pix_fmt yuv420p -profile:v high -movflags +faststart -an hero-loop-1080.mp4
ffmpeg -y -loglevel error -framerate 24 -i $F -vf scale=720:1280:flags=lanczos -c:v libx264 -preset slow -crf 21 -pix_fmt yuv420p -profile:v high -movflags +faststart -an hero-loop-720.mp4
ffmpeg -y -loglevel error -framerate 24 -i $F -c:v libsvtav1 -preset 5 -crf 34 -pix_fmt yuv420p10le -g 48 -an hero-loop-1080-av1.mp4
ffmpeg -y -loglevel error -framerate 24 -i $F -vf scale=720:1280:flags=lanczos -c:v libsvtav1 -preset 5 -crf 36 -pix_fmt yuv420p10le -g 48 -an hero-loop-720-av1.mp4
ffmpeg -y -loglevel error -i hero-loop-1080.mp4 -frames:v 1 -q:v 2 poster-1080.jpg
python3 - <<'PY'
from PIL import Image
im = Image.open('frames/0000.png').convert('RGB')
im.save('poster-1080.avif', quality=60); im.save('poster-1080.webp', quality=78)
im.resize((720, 1280), Image.LANCZOS).save('poster-720.avif', quality=58)
PY
ls -la *.mp4 poster*
