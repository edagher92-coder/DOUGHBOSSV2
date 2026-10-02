"""Dough Boss hero: render previews, the blow-out frame sequence and posters.

Run inside Higgsfield 3D Jutsu `scene_builder_3d_query_python` AFTER
build_exploded_manoush.py has been committed. A query never commits, so the
pose changes and render-only material tweaks below are thrown away afterwards:
the committed scene (and therefore the GLB) keeps its portable materials and
REST pose.

Parameters: prepend one line that defines RENDER_JOB, for example

    RENDER_JOB = {"mode": "frames", "indices": [0, 1, 2, 3, 4, 5, 6, 7]}

Modes
  preview  render `progress` values (default rest and fully exploded)
  frames   render frame k at progress k / (count - 1); max 8 per call
  poster   render the assembled and exploded posters

The pose maths here is the SAME formula the web app must use
(see docs/3d-assets.md): per node, local t = clamp((p - delay) / (1 - 0.3)),
eased with smootherstep, then lerp position and slerp rotation between the
rest and exploded transforms stored on each object by the build script.
"""

import time

import bpy
from mathutils import Quaternion, Vector

MAX_DELAY = 0.3  # must match build_exploded_manoush.py and manifest.json

DEFAULT_JOB = {
    "mode": "preview",
    "progress": [0.0, 1.0],
    "names": None,
    "indices": [],
    "count": 24,
    "size": 1024,
    "samples": 32,
    "enhance": True,
    "hide_peel": False,
    "exposure": 0.0,
    # Camera white balance (K). The 3200 K key stays warm but stops reading
    # as orange when the "film" is balanced a little warmer than daylight.
    "white_balance": 4300.0,
}
try:
    JOB = dict(DEFAULT_JOB, **RENDER_JOB)  # noqa: F821  (prepended by the caller)
except NameError:
    JOB = dict(DEFAULT_JOB)

T0 = time.time()


def clamp01(x):
    return 0.0 if x < 0.0 else 1.0 if x > 1.0 else x


def smootherstep(t):
    t = clamp01(t)
    return t * t * t * (t * (t * 6.0 - 15.0) + 10.0)


def node_progress(p, delay):
    return smootherstep((p - delay) / (1.0 - MAX_DELAY))


def kind_of(obj):
    # In the 3D Jutsu worker, collection iteration can yield None and
    # `"key" in obj` raises for objects without custom properties, so probe
    # defensively instead of trusting either.
    if obj is None:
        return None
    try:
        return obj.get("db_kind")
    except (TypeError, AttributeError):
        return None


def hero_objects():
    return [o for o in bpy.context.scene.objects if kind_of(o) is not None]


def pose_nodes(p):
    for obj in hero_objects():
        e = node_progress(p, float(obj["db_delay"]))
        r0, r1 = Vector(obj["db_rest_loc"]), Vector(obj["db_exp_loc"])
        q0, q1 = Quaternion(obj["db_rest_quat"]), Quaternion(obj["db_exp_quat"])
        obj.rotation_mode = "QUATERNION"
        obj.location = r0.lerp(r1, e)
        obj.rotation_quaternion = q0.slerp(q1, e)
        if obj["db_kind"] == "peel":
            obj.hide_render = bool(JOB["hide_peel"])


# Micro-relief only for the rendered frames. Procedural nodes do not survive
# glTF export, which is fine: this query never commits.
BUMP = {
    "DB_Crust": (420.0, 0.35, 0.0006),
    "DB_Cheese": (300.0, 0.18, 0.0004),
    "DB_Dough": (520.0, 0.20, 0.0004),
    "DB_Wood": (90.0, 0.06, 0.0003),
}


def enhance_materials():
    for mat in bpy.data.materials:
        if mat is None or mat.name not in BUMP or mat.node_tree is None:
            continue
        nt = mat.node_tree
        bsdf = next((n for n in nt.nodes if n.type == "BSDF_PRINCIPLED"), None)
        if bsdf is None:
            continue
        scale, strength, distance = BUMP[mat.name]
        coords = nt.nodes.new("ShaderNodeTexCoord")
        tex = nt.nodes.new("ShaderNodeTexNoise")
        tex.inputs["Scale"].default_value = scale
        tex.inputs["Detail"].default_value = 6.0
        bump = nt.nodes.new("ShaderNodeBump")
        bump.inputs["Strength"].default_value = strength
        if "Distance" in bump.inputs:
            bump.inputs["Distance"].default_value = distance
        nt.links.new(coords.outputs["Object"], tex.inputs["Vector"])
        nt.links.new(tex.outputs["Fac"], bump.inputs["Height"])
        nt.links.new(bump.outputs["Normal"], bsdf.inputs["Normal"])
        if mat.name == "DB_Cheese":
            # A thin glossy coat reads as melted fat under the warm key.
            bsdf.inputs["Coat Weight"].default_value = 0.25
            bsdf.inputs["Coat Roughness"].default_value = 0.22


def setup(size, samples):
    scene = bpy.context.scene
    scene.render.engine = "BLENDER_EEVEE"
    scene.render.film_transparent = True
    scene.render.resolution_x = size
    scene.render.resolution_y = size
    scene.render.resolution_percentage = 100
    scene.eevee.taa_render_samples = samples
    settings = scene.render.image_settings
    try:
        settings.media_type = "IMAGE"
    except (AttributeError, TypeError):
        pass
    settings.file_format = "PNG"
    settings.color_mode = "RGBA"
    settings.color_depth = "8"
    vs = scene.view_settings
    vs.exposure = float(JOB["exposure"])
    if JOB["white_balance"] and hasattr(vs, "use_white_balance"):
        vs.use_white_balance = True
        vs.white_balance_temperature = float(JOB["white_balance"])
    return scene


def plan():
    mode = JOB["mode"]
    if mode == "frames":
        count = int(JOB["count"])
        return [("frame-%03d" % k, k / (count - 1.0)) for k in JOB["indices"]]
    if mode == "poster":
        return [("poster-assembled", 0.0), ("poster-exploded", 1.0)]
    names = JOB["names"] or ["preview-%03d" % round(p * 100) for p in JOB["progress"]]
    return list(zip(names, JOB["progress"]))


def render_all():
    jobs = plan()
    if len(jobs) > 8:
        raise ValueError("3D Jutsu publishes at most 8 files per operation")
    scene = setup(int(JOB["size"]), int(JOB["samples"]))
    if JOB["enhance"]:
        enhance_materials()
    done = []
    for name, p in jobs:
        t = time.time()
        pose_nodes(p)
        bpy.context.view_layer.update()
        target = artifacts.file(name=name + ".png", media_type="image/png")  # noqa: F821
        scene.render.filepath = target.path
        bpy.ops.render.render(write_still=True)
        target.publish()
        done.append({"name": name, "progress": round(p, 6), "seconds": round(time.time() - t, 2)})
    return {
        "mode": JOB["mode"],
        "size": int(JOB["size"]),
        "samples": int(JOB["samples"]),
        "viewTransform": scene.view_settings.view_transform,
        "whiteBalance": getattr(scene.view_settings, "white_balance_temperature", None)
        if getattr(scene.view_settings, "use_white_balance", False)
        else None,
        "posedNodes": len(hero_objects()),
        "rendered": done,
        "seconds": round(time.time() - T0, 2),
    }


result = render_all()
