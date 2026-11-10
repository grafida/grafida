; ============================================================================
; Grafida — Windows installer for the native shell (EXPERIMENTAL, gh-91)
; Joomla content editing, untethered.
;
; Copyright (c) 2026 Nicholas K. Dionysopoulos
; License: GNU General Public License version 3, or later
;
; Compiled by grafida/shell-packager (`windows-installer`, from scripts/make-shell-windows-installer.sh),
; never by hand: it passes the staged tree and these defines (see shell-packager's
; src/Windows/WindowsInstaller.php):
;
;   SRCDIR APPNAME APPID APPEXE WORKEREXE WORKERPHAR ICONFILE APPVERSION VIVERSION PUBLISHER OUTFILE
;   SHELL_PACKAGER_SIGN   only when signing: the command that signs the uninstaller
;   APPURL DISPLAYNAME    from shell-package.json's windows.installer.defines
;
; It installs SIDE BY SIDE with Boson's build (build/windows-installer.nsi) and touches nothing of
; it: Boson's goes to %LOCALAPPDATA%\Programs\Grafida under the uninstall key "Grafida"; this one
; goes to %LOCALAPPDATA%\Programs\<APPID> (app.grafida) under the key "<APPID>", with its own Start
; menu entry. The user's data (%APPDATA%\Grafida, the database and settings) is the same one both
; builds use, and the uninstaller leaves it alone.
;
; Per user, no elevation. No WebView2 bootstrapper: the runtime ships with Windows 11 and is
; installed by Windows Update on Windows 10; without it the installer warns and offers the download
; page, and the shell itself says so at launch.
; ============================================================================

Unicode true
!include "MUI2.nsh"
!include "LogicLib.nsh"

; A signed build signs the uninstaller as makensis writes it: it lives inside the installer, and
; nothing can reach it afterwards.
!ifdef SHELL_PACKAGER_SIGN
  !uninstfinalize '${SHELL_PACKAGER_SIGN}' = 0
!endif

!define UNINSTKEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APPID}"
!define SHORTCUT  "$SMPROGRAMS\${DISPLAYNAME}.lnk"
!define WEBVIEW2_GUID "{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}"
!define WEBVIEW2_URL  "https://go.microsoft.com/fwlink/p/?LinkId=2124703"

Name "${DISPLAYNAME}"
OutFile "${OUTFILE}"
RequestExecutionLevel user
InstallDir "$LOCALAPPDATA\Programs\${APPID}"
InstallDirRegKey HKCU "${UNINSTKEY}" "InstallLocation"
SetCompressor /SOLID lzma
VIProductVersion "${VIVERSION}"
VIAddVersionKey "ProductName"     "${APPNAME}"
VIAddVersionKey "FileDescription" "${DISPLAYNAME} installer"
VIAddVersionKey "CompanyName"     "${PUBLISHER}"
VIAddVersionKey "LegalCopyright"  "Copyright (c) 2026 ${PUBLISHER}"
VIAddVersionKey "FileVersion"     "${APPVERSION}"
VIAddVersionKey "ProductVersion"  "${APPVERSION}"

!ifdef ICONFILE
  !define MUI_ICON   "${ICONFILE}"
  !define MUI_UNICON "${ICONFILE}"
!endif
!define MUI_ABORTWARNING

!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_LICENSE "${SRCDIR}\LICENSE.txt"
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!define MUI_FINISHPAGE_RUN "$INSTDIR\${APPEXE}"
!define MUI_FINISHPAGE_RUN_TEXT "Launch ${APPNAME}"
!insertmacro MUI_PAGE_FINISH

!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

!insertmacro MUI_LANGUAGE "English"

; The WebView2 Evergreen runtime, per machine or per user (Microsoft's documented detection).
Function .onInit
  ReadRegStr $0 HKLM "SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\${WEBVIEW2_GUID}" "pv"
  ${If} $0 == ""
  ${OrIf} $0 == "0.0.0.0"
    ReadRegStr $0 HKCU "Software\Microsoft\EdgeUpdate\Clients\${WEBVIEW2_GUID}" "pv"
  ${EndIf}
  ${If} $0 == ""
  ${OrIf} $0 == "0.0.0.0"
    IfSilent done
    MessageBox MB_YESNO|MB_ICONEXCLAMATION "${APPNAME} needs the Microsoft Edge WebView2 Runtime, which is not installed on this computer.$\r$\n$\r$\nOpen its download page now? You can install ${APPNAME} first and the runtime afterwards." IDNO done
    ExecShell "open" "${WEBVIEW2_URL}"
    done:
  ${EndIf}
FunctionEnd

Section "Install"
  SetOutPath "$INSTDIR"
  File "${SRCDIR}\${APPEXE}"
  File "${SRCDIR}\${WORKEREXE}"
  File "${SRCDIR}\${WORKERPHAR}"
  File "${SRCDIR}\shell.json"
  File "${SRCDIR}\LICENSE.txt"
!ifdef ICONFILE
  File "${ICONFILE}"
!endif
  WriteUninstaller "$INSTDIR\uninstall.exe"

  CreateShortCut "${SHORTCUT}" "$INSTDIR\${APPEXE}"

  WriteRegStr HKCU "${UNINSTKEY}" "DisplayName"     "${DISPLAYNAME}"
  WriteRegStr HKCU "${UNINSTKEY}" "DisplayVersion"  "${APPVERSION}"
  WriteRegStr HKCU "${UNINSTKEY}" "Publisher"       "${PUBLISHER}"
  WriteRegStr HKCU "${UNINSTKEY}" "URLInfoAbout"    "${APPURL}"
  WriteRegStr HKCU "${UNINSTKEY}" "DisplayIcon"     "$INSTDIR\${APPEXE}"
  WriteRegStr HKCU "${UNINSTKEY}" "InstallLocation" "$INSTDIR"
  WriteRegStr HKCU "${UNINSTKEY}" "UninstallString" '"$INSTDIR\uninstall.exe"'
  WriteRegStr HKCU "${UNINSTKEY}" "QuietUninstallString" '"$INSTDIR\uninstall.exe" /S'
  WriteRegDWORD HKCU "${UNINSTKEY}" "NoModify" 1
  WriteRegDWORD HKCU "${UNINSTKEY}" "NoRepair" 1
SectionEnd

; What the installer wrote, and nothing else: not %APPDATA%\Grafida (the user's data, shared with
; Boson's build), not the WebView2 profile in %LOCALAPPDATA%\${APPID}.
Section "Uninstall"
  Delete "$INSTDIR\${APPEXE}"
  Delete "$INSTDIR\${WORKEREXE}"
  Delete "$INSTDIR\${WORKERPHAR}"
  Delete "$INSTDIR\shell.json"
  Delete "$INSTDIR\LICENSE.txt"
  Delete "$INSTDIR\${APPNAME}.ico"
  Delete "$INSTDIR\uninstall.exe"
  RMDir "$INSTDIR"
  Delete "${SHORTCUT}"
  DeleteRegKey HKCU "${UNINSTKEY}"
SectionEnd
