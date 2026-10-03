// three.js scene for the hero "exploded manoush". Bundled with GLTFLoader and the meshopt
// decoder only; nothing else from three/examples is pulled in.
//
// The page owns lighting and the camera (the GLB has neither). Poses come from the build-time
// pose data, which was validated against the node-name contract; here the loaded GLB is
// re-checked against that data and the scene refuses to start on any mismatch.

import {
  Color,
  DirectionalLight,
  HemisphereLight,
  Mesh,
  NeutralToneMapping,
  Object3D,
  PerspectiveCamera,
  Scene,
  SRGBColorSpace,
  WebGLRenderer,
} from "three";
import { GLTFLoader } from "three/examples/jsm/loaders/GLTFLoader.js";
import { MeshoptDecoder } from "three/examples/jsm/libs/meshopt_decoder.module.js";
import { fovForAspect, poseAt, type PoseAt, type PoseData } from "./pose";

export interface SceneSize {
  width: number;
  height: number;
}

export interface HeroScene {
  /** Apply the global explosion progress p in [0, 1] (does not render). */
  setProgress(p: number): void;
  /** Render the current pose once. */
  render(): void;
  /** Re-measure the canvas (CSS size, or the explicit size given at creation). */
  resize(): void;
  /** Release every GPU resource and the WebGL context. Idempotent. */
  dispose(): void;
}

export class SceneError extends Error {
  readonly code: string;
  constructor(code: string, message: string) {
    super(message);
    this.name = "SceneError";
    this.code = code;
  }
}

const REST_TOLERANCE = 1e-3; // 12-bit quantisation step plus slack (docs/3d-assets.md)

function parseGlb(glb: ArrayBuffer): Promise<Object3D> {
  return new Promise((resolve, reject) => {
    const loader = new GLTFLoader();
    loader.setMeshoptDecoder(MeshoptDecoder);
    loader.parse(
      glb,
      "",
      (gltf) => resolve(gltf.scene),
      (err) => reject(new SceneError("glb-parse", "GLB could not be parsed: " + (err && err.message ? err.message : "unknown error"))),
    );
  });
}

function collectNodes(root: Object3D): Map<string, Object3D[]> {
  const byName = new Map<string, Object3D[]>();
  root.traverse((obj) => {
    if (!obj.name) return;
    const list = byName.get(obj.name);
    if (list) list.push(obj);
    else byName.set(obj.name, [obj]);
  });
  return byName;
}

function disposeObject(root: Object3D): void {
  root.traverse((obj) => {
    const mesh = obj as Mesh;
    if (mesh.geometry) mesh.geometry.dispose();
    const material = mesh.material;
    const list = Array.isArray(material) ? material : material ? [material] : [];
    list.forEach((mat) => {
      Object.keys(mat).forEach((key) => {
        const value = (mat as unknown as Record<string, unknown>)[key];
        if (value && typeof value === "object" && typeof (value as { dispose?: unknown }).dispose === "function") {
          (value as { dispose: () => void }).dispose();
        }
      });
      mat.dispose();
    });
  });
}

export async function createHeroScene(
  canvas: HTMLCanvasElement,
  glb: ArrayBuffer,
  poses: PoseData,
  size?: SceneSize,
): Promise<HeroScene> {
  let renderer: WebGLRenderer;
  try {
    renderer = new WebGLRenderer({ canvas, antialias: true, alpha: true, premultipliedAlpha: true, powerPreference: "default" });
  } catch {
    throw new SceneError("webgl2-unavailable", "WebGL2 is not available");
  }

  const scene = new Scene();
  let root: Object3D | null = null;
  let disposed = false;

  const release = (): void => {
    if (disposed) return;
    disposed = true;
    if (root) disposeObject(root);
    scene.clear();
    renderer.dispose();
    renderer.forceContextLoss();
  };

  try {
    renderer.setClearColor(new Color(0x000000), 0);
    renderer.outputColorSpace = SRGBColorSpace;
    renderer.toneMapping = NeutralToneMapping; // matches the Khronos PBR Neutral view transform of the frames

    root = await parseGlb(glb);

    // Re-check the loaded GLB against the contract and the pose data (fail closed).
    const byName = collectNodes(root);
    const objects: Object3D[] = [];
    for (const row of poses.nodes) {
      const found = byName.get(row[0]);
      if (!found || found.length !== 1) {
        throw new SceneError("contract", "GLB node '" + row[0] + "' must exist exactly once");
      }
      const obj = found[0] as Object3D;
      for (let i = 0; i < 3; i += 1) {
        if (Math.abs(obj.position.getComponent(i) - (row[1][i] as number)) > REST_TOLERANCE) {
          throw new SceneError("contract", "GLB node '" + row[0] + "' does not match its rest pose");
        }
      }
      objects.push(obj);
    }
    scene.add(root);

    // Lights follow the render rig in docs/3d-assets.md: warm key (3200 K) from the upper
    // left, 4300 K rim from behind, soft 5600 K fill.
    const key = new DirectionalLight(0xffb878, 3.2);
    key.position.set(-0.45, 0.65, 0.35);
    const rim = new DirectionalLight(0xffd0a8, 2.0);
    rim.position.set(0.25, 0.3, -0.55);
    const fill = new HemisphereLight(0xffece0, 0x2a1a10, 0.9);
    scene.add(key, rim, fill);

    const cam = poses.camera;
    const camera = new PerspectiveCamera(cam.fovDeg, 1, 0.02, 4);
    camera.position.set(cam.position[0] as number, cam.position[1] as number, cam.position[2] as number);
    camera.lookAt(cam.target[0] as number, cam.target[1] as number, cam.target[2] as number);

    const scratch: PoseAt = { position: [0, 0, 0], quaternion: [0, 0, 0, 1] };

    const resize = (): void => {
      const w = Math.max(1, Math.floor(size ? size.width : canvas.clientWidth || canvas.width));
      const h = Math.max(1, Math.floor(size ? size.height : canvas.clientHeight || canvas.height));
      const dpr = typeof window !== "undefined" && window.devicePixelRatio ? Math.min(window.devicePixelRatio, 2) : 1;
      renderer.setPixelRatio(dpr);
      renderer.setSize(w, h, false);
      camera.aspect = w / h;
      camera.fov = fovForAspect(cam.fovDeg, camera.aspect);
      camera.updateProjectionMatrix();
    };

    const setProgress = (p: number): void => {
      for (let i = 0; i < poses.nodes.length; i += 1) {
        const row = poses.nodes[i];
        const obj = objects[i];
        if (!row || !obj) continue;
        poseAt(row, p, poses.maxDelay, scratch);
        obj.position.set(scratch.position[0] as number, scratch.position[1] as number, scratch.position[2] as number);
        obj.quaternion.set(
          scratch.quaternion[0] as number,
          scratch.quaternion[1] as number,
          scratch.quaternion[2] as number,
          scratch.quaternion[3] as number,
        );
        obj.updateMatrix();
      }
    };

    resize();
    setProgress(0);

    return {
      setProgress,
      render: () => {
        if (!disposed) renderer.render(scene, camera);
      },
      resize: () => {
        if (!disposed) resize();
      },
      dispose: release,
    };
  } catch (err) {
    release();
    throw err;
  }
}
