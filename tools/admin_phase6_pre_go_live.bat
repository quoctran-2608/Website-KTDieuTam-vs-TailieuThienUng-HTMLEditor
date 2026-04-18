@echo off
setlocal enabledelayedexpansion

set "ROOT_DIR=%~dp0.."
pushd "%ROOT_DIR%" >nul

echo [1/4] Static pre-go-live checker (Python)
python tools\admin_phase6_pre_go_live_check.py --format text
if errorlevel 1 goto :fail

echo.
echo [2/4] Site readiness audit ^(output captured^)
set "READINESS_REPORT=%TEMP%\admin-phase6-site-readiness.txt"
python tools\site_html_readiness_audit.py > "%READINESS_REPORT%"
if errorlevel 1 goto :fail
echo Saved readiness report: %READINESS_REPORT%
echo Top lines:
powershell -NoProfile -Command "Get-Content -Path '%READINESS_REPORT%' -TotalCount 30"

echo.
echo [3/4] Suggested PHP healthcheck ^(manual if PHP exists^)
where php >nul 2>nul
if %errorlevel%==0 (
  php admin\includes\healthcheck.php
  if errorlevel 1 goto :fail
) else (
  echo php CLI not found in this environment. Skip runtime healthcheck.
)

echo.
echo [4/4] Done.
echo If all checks above are OK, internal go-live is ready.
popd >nul
exit /b 0

:fail
echo.
echo Pre-go-live script failed. Review errors above before go-live.
popd >nul
exit /b 1

