@echo off
setlocal
cd /d "%~dp0.."

if not exist "runtime\php\php.exe" (
    echo REGIS+ PHP runtime not found at runtime\php\php.exe
    exit /b 1
)

"runtime\php\php.exe" tools\backup.php %*
exit /b %errorlevel%
