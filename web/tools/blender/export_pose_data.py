"""Dough Boss hero: export pose data from the committed scene as a PNG.

Run inside Higgsfield 3D Jutsu `scene_builder_3d_query_python` after
build_exploded_manoush.py. 3D Jutsu only publishes PNG/JPEG/MP4 artifacts, so
the pose JSON is packed byte-for-byte into the RGB channels of a one-row,
8-bit, Non-Color PNG ("pose-data.png"). tools/blender/make_manifest.mjs
decodes it, checks the SHA-256 and writes public/hero/manifest.json. This
avoids hand-copying numbers out of a tool transcript.

Layout of the byte stream (before padding to a multiple of 3):
  b"DBPOSE1\\0" | uint32 big-endian payload length | 32-byte SHA-256 | payload
"""

import hashlib
import json
import math
import struct

import bpy
from mathutils import Vector

MAX_WIDTH = 16384  # 3D Jutsu's per-side image limit; one row keeps decoding trivial


def kind_of(obj):
    if obj is None:
        return None
    try:
        return obj.get("db_kind")
    except (TypeError, AttributeError):
        return None


def gltf_pos(v):
    return [round(v[0], 6), round(v[2], 6), round(-v[1], 6)]


def gltf_quat(q):
    return [round(q[1], 6), round(q[3], 6), round(-q[2], 6), round(q[0], 6)]


ORDER = {"peel": 0, "dough": 1, "rim": 2, "topping": 3, "mint": 4, "chili": 5}


def sort_key(obj):
    kind = obj["db_kind"]
    slice_index = int(obj["db_slice"])
    if slice_index >= 0:
        return (1, slice_index, ORDER[kind], obj.name)
    return (0 if kind == "peel" else 2, ORDER[kind], 0, obj.name)


def collect():
    scene = bpy.context.scene
    objs = sorted((o for o in scene.objects if kind_of(o) is not None), key=sort_key)
    nodes = []
    for obj in objs:
        nodes.append({
            "name": obj.name,
            "kind": obj["db_kind"],
            "slice": None if int(obj["db_slice"]) < 0 else int(obj["db_slice"]),
            "layer": int(obj["db_layer"]),
            "rest": {"position": gltf_pos(obj["db_rest_loc"]), "quaternion": gltf_quat(obj["db_rest_quat"])},
            "exploded": {"position": gltf_pos(obj["db_exp_loc"]), "quaternion": gltf_quat(obj["db_exp_quat"])},
            "delay": round(float(obj["db_delay"]), 4),
            "vertices": len(obj.data.vertices),
        })
    cam = scene.camera
    forward = cam.matrix_world.to_quaternion() @ Vector((0.0, 0.0, -1.0))
    target = cam.get("db_target")  # written by the build script's camera fit
    lens = cam.data.lens
    sensor = cam.data.sensor_width
    fov = 2.0 * math.degrees(math.atan((sensor / 2.0) / lens))
    return {
        "blender": bpy.app.version_string,
        "camera": {
            "position": gltf_pos(cam.location),
            "forward": gltf_pos(forward),
            "fovDeg": round(fov, 3),
            "lensMm": lens,
            "sensorMm": sensor,
            "quaternion": gltf_quat(cam.matrix_world.to_quaternion()),
            "target": None if target is None else gltf_pos(list(target)),
        },
        "nodes": nodes,
    }


def encode(payload):
    digest = hashlib.sha256(payload).digest()
    stream = b"DBPOSE1\x00" + struct.pack(">I", len(payload)) + digest + payload
    stream += b"\x00" * ((-len(stream)) % 3)
    width = len(stream) // 3
    if width > MAX_WIDTH:
        raise ValueError("pose data too large for one row: %d px" % width)
    img = bpy.data.images.new("db_pose_data", width, 1, alpha=True, float_buffer=False)
    img.colorspace_settings.name = "Non-Color"
    px = []
    for i in range(width):
        r, g, b = stream[3 * i], stream[3 * i + 1], stream[3 * i + 2]
        px.extend((r / 255.0, g / 255.0, b / 255.0, 1.0))
    img.pixels.foreach_set(px)
    target = artifacts.file(name="pose-data.png", media_type="image/png")  # noqa: F821
    img.filepath_raw = target.path
    img.file_format = "PNG"
    img.save()
    target.publish()
    return {"bytes": len(payload), "sha256": hashlib.sha256(payload).hexdigest(), "width": width}


data = collect()
result = encode(json.dumps(data, separators=(",", ":"), sort_keys=True).encode("utf-8"))
result["nodes"] = len(data["nodes"])
