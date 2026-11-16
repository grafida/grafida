# Release and signing quirks

Read before verifying a Windows build's signature on macOS or placing the human-verification breakpoint in a release.

On macOS, the Windows `signtool verify` output "Signature verification: failed" is a **local trust-chain quirk,
not a build break**.

**Why:** the build host does not have the Windows trust chain, so verification reports a failure for a correctly
signed binary.

**How to apply:** do not treat that line as a failed build; check the signature through the procedure in
`build/readme/04-exe-signing-on-macos.md` and the release procedure in `.claude/rules/build-and-packaging.md`.
