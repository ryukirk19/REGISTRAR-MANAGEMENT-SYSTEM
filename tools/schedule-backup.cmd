@echo off
setlocal
cd /d "%~dp0.."

echo This registers a Windows task that runs the REGIS+ database backup
echo every Friday at 4:00 PM while this PC is on.
echo.

schtasks /Create /F /SC WEEKLY /D FRI /ST 16:00 /TN "REGIS+ Database Backup" /TR "cmd /c \"%~dp0backup.cmd\""
if errorlevel 1 (
    echo.
    echo The task was not created. Open Command Prompt as Administrator and run this file again.
    pause
    exit /b 1
)

echo.
echo Task "REGIS+ Database Backup" is registered.
echo Copies are written to database\backups and the last eight are kept.
pause
