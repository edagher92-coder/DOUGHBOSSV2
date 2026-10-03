import type { MetadataRoute } from "next";
import { siteUrl } from "@/lib/seo";

// Home only for now; no lastModified because we have no real change date to state.
export default function sitemap(): MetadataRoute.Sitemap {
  return [{ url: `${siteUrl()}/`, changeFrequency: "weekly", priority: 1 }];
}
