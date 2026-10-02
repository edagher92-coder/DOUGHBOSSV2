import type { MetadataRoute } from "next";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "Dough Boss",
    short_name: "Dough Boss",
    start_url: "/",
    display: "standalone",
    theme_color: "#070707",
    background_color: "#070707",
    icons: [{ src: "/favicon.svg", sizes: "any", type: "image/svg+xml" }],
  };
}
