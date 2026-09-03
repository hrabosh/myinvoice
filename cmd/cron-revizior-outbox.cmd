@echo off
REM ReviziOR outbox — doruceni udalosti o dokladech (spoustet kazdou minutu).
setlocal
set "PROJECT_ROOT=%~dp0.."
php "%PROJECT_ROOT%\api\bin\cron-revizior-outbox.php" %*
endlocal
