# Grafida — Joomla content editing, untethered.
#
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later
#
# The upgrade test's --token-from-store on Windows (gh-92): prints the secret Grafida keeps for
# REFERENCE, i.e. %APPDATA%\Grafida\secrets\<sha1(REFERENCE)>.bin, the base64 of a CurrentUser DPAPI
# blob with no entropy (Secret\WindowsSecretStore). DPAPI is keyed to the logon, so it must run in the
# desktop session, which grafida/shell's `winvm.sh desktop VM tests/Upgrade/windows-token.ps1 REFERENCE`
# does; a key-authenticated SSH session cannot unprotect it.
param([Parameter(Mandatory)] [string] $Reference)
Add-Type -AssemblyName System.Security
$sha = -join ([Security.Cryptography.SHA1]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($Reference)) | ForEach-Object { $_.ToString('x2') })
$file = "$env:APPDATA\Grafida\secrets\$sha.bin"
$bytes = [Security.Cryptography.ProtectedData]::Unprotect([Convert]::FromBase64String((Get-Content -Raw $file).Trim()), $null, 'CurrentUser')
Write-Output ([Text.Encoding]::UTF8.GetString($bytes))
