@echo off
rem === START.bat — jalankan XML Faktur Toolkit ===
rem Menyalakan server web di jendela terpisah, menunggu sampai siap,
rem lalu membuka browser. Tutup JENDELA SERVER untuk mematikan aplikasi.

setlocal
cd /d "%~dp0"
title XML Faktur Toolkit

rem --- cari php.exe: folder lokal, folder php di root proyek, Laragon, atau PATH ---
set "PHP_EXE="
if exist "%~dp0php\php.exe" set "PHP_EXE=%~dp0php\php.exe"
if not defined PHP_EXE if exist "%~dp0..\php\php.exe" set "PHP_EXE=%~dp0..\php\php.exe"

if not defined PHP_EXE if exist "C:\laragon\bin\php\php-*\php.exe" (
    for /d %%D in ("C:\laragon\bin\php\php-*") do set "PHP_EXE=%%D\php.exe"
)

if not defined PHP_EXE where php >nul 2>&1 && set "PHP_EXE=php"

if not defined PHP_EXE (
    echo [ERROR] PHP tidak ditemukan.
    echo         Letakkan folder "php" di samping folder v3 atau instal PHP dan masukkan ke PATH.
    echo.
    pause
    exit /b 1
)

echo ============================================
echo   XML Faktur Toolkit
echo ============================================
echo.
echo Server akan dinyalakan di JENDELA TERPISAH (minimize).
echo.
echo   ^>^> JANGAN tutup jendela server selagi dipakai.
echo   ^>^> Untuk mematikan aplikasi: tutup jendela server.
echo.

rem --- nyalakan server di jendela terpisah ---
start "XML Faktur Toolkit — SERVER (jangan ditutup)" /min cmd /c ""%PHP_EXE%" -c "%~dp0." -d upload_max_filesize=100M -d post_max_size=120M -d memory_limit=512M -S 0.0.0.0:8080 -t "%~dp0app""

rem --- tunggu sampai server siap (maks 30 detik, probe via php fsockopen) ---
echo Menunggu server siap...
set "TRIES=0"
:waitloop
"%PHP_EXE%" -r "exit(@fsockopen('127.0.0.1',8080,$e,$s,1)?0:1);" >nul 2>&1 && goto ready
timeout /t 1 /nobreak >nul
set /a TRIES+=1
if %TRIES% lss 30 goto waitloop
goto serverfail

:serverfail
echo.
echo [ERROR] Server tidak bisa dinyalakan. Kemungkinan port 8080 dipakai
echo         aplikasi lain. Tutup aplikasi lain di port 8080 lalu coba lagi.
echo.
pause
exit /b 1

:ready
echo Server SIAP: http://localhost:8080
echo Membuka browser...
start "" http://localhost:8080

echo.
echo Aplikasi berjalan. Jendela ini boleh ditutup.
echo (Server tetap hidup di jendela minimize sampai jendela itu ditutup)
echo.
pause
