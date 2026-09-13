// Keep dev-only files out of the built/signed .xpi.
module.exports = {
  ignoreFiles: [
    "package.json",
    "package-lock.json",
    "node_modules",
    "web-ext-artifacts",
    "web-ext-config.cjs",
    "README.md",
  ],
};
