; ============================================================================
; Grafida — Windows installer (gh-91, gh-92)
; Joomla content editing, untethered.
;
; Copyright (c) 2026 Nicholas K. Dionysopoulos
; License: GNU General Public License version 3, or later
;
; Compiled by grafida/shell-packager (`windows-installer`, from scripts/make-windows-installer.sh),
; never by hand: it passes the staged tree and these defines (see shell-packager's
; src/Windows/WindowsInstaller.php):
;
;   SRCDIR APPNAME APPID APPEXE WORKEREXE WORKERPHAR ICONFILE APPVERSION VIVERSION PUBLISHER OUTFILE
;   SHELL_PACKAGER_SIGN   only when signing: the command that signs the uninstaller
;   APPURL DISPLAYNAME COPYRIGHT   from shell-package.json's windows.installer.defines
;
; It takes over the identity of the Boson build it replaces (gh-92): %LOCALAPPDATA%\Programs\Grafida
; (or wherever HKCU\Software\Grafida\InstallDir says), the uninstall key "Grafida" and the Start menu
; entry Grafida\Grafida.lnk. The executable keeps the path Boson's had (NTFS ignores case), so a
; taskbar pin keeps working. Before it writes anything, it:
;
;   1. makes sure neither this install nor a preview install is running (ENSURE_NOT_RUNNING);
;   2. removes a Boson install found in the folder (its libboson DLL or grafida.phar is the tell):
;      Boson's own uninstall.exe, silently and in place (`/S _?=`), waited for; then Boson's known
;      files itself, whatever that uninstaller did or failed to do;
;   3. removes a preview install (gh-91: %LOCALAPPDATA%\Programs\<APPID>, the key "<APPID>", the
;      shortcut "<APPNAME> (shell preview)") the same way.
;
; Cancelled after step 2 or 3, neither build is left; the user's data (%APPDATA%\Grafida: the
; database and settings, which every build shares) is never touched, by the installer or by any of
; the uninstallers.
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

!define UNINSTKEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APPNAME}"
!define DIRKEY    "Software\${APPNAME}"
!define SHORTCUT_DIR "$SMPROGRAMS\${APPNAME}"
!define SHORTCUT  "${SHORTCUT_DIR}\${APPNAME}.lnk"
; The preview install this one replaces (gh-91's build).
!define PREVIEW_DIR      "$LOCALAPPDATA\Programs\${APPID}"
!define PREVIEW_KEY      "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APPID}"
!define PREVIEW_SHORTCUT "$SMPROGRAMS\${APPNAME} (shell preview).lnk"
!define WEBVIEW2_GUID "{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}"
!define WEBVIEW2_URL  "https://go.microsoft.com/fwlink/p/?LinkId=2124703"

Name "${DISPLAYNAME}"
OutFile "${OUTFILE}"
RequestExecutionLevel user
; The footer of every page names the product, not the tool (NSIS's default is its own name).
BrandingText "${DISPLAYNAME} ${APPVERSION}"
InstallDir "$LOCALAPPDATA\Programs\${APPNAME}"
; Boson's key, which this build keeps writing. Read when the installer starts, i.e. before Boson's
; uninstaller (which deletes it) runs.
InstallDirRegKey HKCU "${DIRKEY}" "InstallDir"
SetCompressor /SOLID lzma
VIProductVersion "${VIVERSION}"
VIAddVersionKey "ProductName"     "${APPNAME}"
VIAddVersionKey "FileDescription" "${DISPLAYNAME} installer"
VIAddVersionKey "CompanyName"     "${PUBLISHER}"
VIAddVersionKey "LegalCopyright"  "${COPYRIGHT}"
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

; A running Grafida holds its executables open, and then an install cannot write them (NSIS's
; "Error opening file for writing", Abort/Retry/Ignore) and an uninstall skips them without a word
; while reporting success, its key already gone (both seen on the clean guests, gh-91). So before
; touching a file, each side opens the executables for writing: a running one refuses. In the
; install folder that covers Boson's build too: its grafida.exe is ${APPEXE} on NTFS, which ignores
; case. Silent mode cannot ask: it ends with exit code 2 and changes nothing.
!macro PROBE_FILE path id
  ${If} ${FileExists} "${path}"
    FileOpen $9 "${path}" a
    IfErrors busy_${id}
    FileClose $9
  ${EndIf}
!macroend

!macro ENSURE_NOT_RUNNING_IN dir id
  retry_${id}:
  ClearErrors
  !insertmacro PROBE_FILE "${dir}\${APPEXE}" ${id}
  !insertmacro PROBE_FILE "${dir}\${WORKEREXE}" ${id}
  Goto free_${id}
  busy_${id}:
  IfSilent 0 +3
    SetErrorLevel 2
    Abort
  ; English on every Windows, buttons in the OS language: deliberate (gh-92), see build-and-packaging.md.
  MessageBox MB_RETRYCANCEL|MB_ICONEXCLAMATION "${APPNAME} is running. Close it, then click Retry." IDRETRY retry_${id}
  Abort
  free_${id}:
!macroend

; Each removal ends with the files it knows about deleted by name, so an uninstaller that is missing,
; fails or leaves something behind changes nothing. `_?=` runs an uninstaller in place, so ExecWait
; really waits for it (otherwise it copies itself to %TEMP% and returns at once); it then cannot
; delete itself, which is the last line here.
!macro REMOVE_BOSON
  ${If} ${FileExists} "$INSTDIR\libboson-windows-x86_64.dll"
  ${OrIf} ${FileExists} "$INSTDIR\grafida.phar"
    DetailPrint "Removing the previous version of ${APPNAME}"
    ${If} ${FileExists} "$INSTDIR\uninstall.exe"
      ExecWait '"$INSTDIR\uninstall.exe" /S _?=$INSTDIR'
    ${EndIf}
    Delete "$INSTDIR\grafida.phar"
    Delete "$INSTDIR\*.dll"
    RMDir /r "$INSTDIR\assets"
    Delete "$INSTDIR\uninstall.exe"
    Delete "${SHORTCUT_DIR}\Uninstall ${APPNAME}.lnk"
    DeleteRegKey HKCU "${DIRKEY}"
  ${EndIf}
!macroend

!macro REMOVE_PREVIEW
  ${If} ${FileExists} "${PREVIEW_DIR}\${APPEXE}"
  ${OrIf} ${FileExists} "${PREVIEW_DIR}\uninstall.exe"
    DetailPrint "Removing the ${APPNAME} shell preview"
    ${If} ${FileExists} "${PREVIEW_DIR}\uninstall.exe"
      ExecWait '"${PREVIEW_DIR}\uninstall.exe" /S _?=${PREVIEW_DIR}'
    ${EndIf}
    Delete "${PREVIEW_DIR}\${APPEXE}"
    Delete "${PREVIEW_DIR}\${WORKEREXE}"
    Delete "${PREVIEW_DIR}\${WORKERPHAR}"
    Delete "${PREVIEW_DIR}\shell.json"
    Delete "${PREVIEW_DIR}\LICENSE.txt"
    Delete "${PREVIEW_DIR}\${APPNAME}.ico"
    Delete "${PREVIEW_DIR}\uninstall.exe"
    RMDir "${PREVIEW_DIR}"
    Delete "${PREVIEW_SHORTCUT}"
    DeleteRegKey HKCU "${PREVIEW_KEY}"
  ${EndIf}
!macroend

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
  !insertmacro ENSURE_NOT_RUNNING_IN "$INSTDIR" here
  !insertmacro ENSURE_NOT_RUNNING_IN "${PREVIEW_DIR}" preview
  !insertmacro REMOVE_BOSON
  !insertmacro REMOVE_PREVIEW

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

  CreateDirectory "${SHORTCUT_DIR}"
  CreateShortCut "${SHORTCUT}" "$INSTDIR\${APPEXE}"

  WriteRegStr HKCU "${DIRKEY}" "InstallDir" "$INSTDIR"

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

; What the installer wrote, and nothing else: not %APPDATA%\Grafida (the user's data), not the
; WebView2 profile in %LOCALAPPDATA%\${APPID}.
Section "Uninstall"
  !insertmacro ENSURE_NOT_RUNNING_IN "$INSTDIR" here
  Delete "$INSTDIR\${APPEXE}"
  Delete "$INSTDIR\${WORKEREXE}"
  Delete "$INSTDIR\${WORKERPHAR}"
  Delete "$INSTDIR\shell.json"
  Delete "$INSTDIR\LICENSE.txt"
  Delete "$INSTDIR\${APPNAME}.ico"
  Delete "$INSTDIR\uninstall.exe"
  RMDir "$INSTDIR"
  Delete "${SHORTCUT}"
  RMDir "${SHORTCUT_DIR}"
  DeleteRegKey HKCU "${UNINSTKEY}"
  DeleteRegKey HKCU "${DIRKEY}"
SectionEnd
