import { readFileSync } from "node:fs";
import path from "node:path";
import { afterEach, describe, expect, it, vi } from "vitest";
import HomePage, { STORES_UNAVAILABLE_MESSAGE } from "@/app/page";
import { STORES } from "@/lib/data/catalogue";
import { CatalogueUnavailableError } from "@/lib/errors";
import * as data from "@/lib/data";

vi.mock("@/lib/data", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/data")>();
  return { ...actual, getStores: vi.fn(actual.getStores) };
});

const SRC = path.resolve(__dirname, "../../src");
const read = (rel: string) => readFileSync(path.join(SRC, rel), "utf8");

afterEach(() => vi.restoreAllMocks());

/** Serialise an element tree (functions dropped) so we can look for text and component props. */
const dump = (node: unknown) => JSON.stringify(node, (_k, v) => (typeof v === "function" ? "[fn]" : v));

describe("home page store loading", () => {
  it("loads stores through getStores(), the single data path", async () => {
    vi.spyOn(console, "warn").mockImplementation(() => {});
    const tree = await HomePage();
    expect(data.getStores).toHaveBeenCalled();
    expect(dump(tree)).toContain(STORES[0]!.slug);
    expect(dump(tree)).not.toContain(STORES_UNAVAILABLE_MESSAGE);
  });

  it("fails closed: when getStores() throws it says so and renders no store data", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    vi.mocked(data.getStores).mockRejectedValueOnce(new CatalogueUnavailableError("Stores could not be loaded."));
    const tree = dump(await HomePage());
    expect(tree).toContain(STORES_UNAVAILABLE_MESSAGE);
    for (const s of STORES) expect(tree).not.toContain(s.slug);
  });

  it("does not import the static STORES seed directly", () => {
    expect(read("app/page.tsx")).not.toMatch(/data\/catalogue/);
  });
});

describe("in-page navigation anchors", () => {
  const sources = ["app/page.tsx", "components/layout/SiteFooter.tsx", "components/teaser/ComingSoonSection.tsx", "app/layout.tsx"].map(read).join("\n");
  const ids = new Set([...sources.matchAll(/\bid="([^"{}]+)"/g)].map((m) => m[1]));

  it("every #anchor linked from the header, the layout skip link and the page resolves to an element id", () => {
    const linked = [...[read("components/layout/SiteHeader.tsx"), sources].join("\n").matchAll(/href[=:]\s*"#([\w-]+)"/g)].map((m) => m[1]!);
    expect(linked.length).toBeGreaterThan(0);
    for (const target of linked) expect(ids, `#${target} has no matching id`).toContain(target);
  });

  it("the dead #order anchor is gone", () => {
    expect(read("components/layout/SiteHeader.tsx")).not.toContain("#order");
  });
});
