# acorn 8.14.0 (vendored)

This is a JavaScript parser used only by `ops/qwen/qwen-site-task.mjs`. The runner uses it to find exact function boundaries, so the edit-scope guard and the JavaScript engine always agree on where a function ends. It is **not** shipped in the plugin zip, which packages only `includes/`, `admin/`, `public/` and root files.

- Source: https://github.com/acornjs/acorn, npm `acorn@8.14.0`, file `dist/acorn.mjs`, unmodified.
- npm integrity of the tarball: `sha512-cl669nCJTZBsL97OF4kUQm5g5hC2uihk0NxY3WENAC0TYdILVkAyHymAntgxGkl7K+t0cXIrH5siy5S4XkFycA==` (checked against the downloaded tarball).
- SHA-256 of `acorn.mjs`: `402f6759c744ea2ca501c1dc4ded8b1c54c9066cc7a741837057dcb68bc64c93`.
- License: MIT. The full text is in `LICENSE`.
