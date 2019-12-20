@echo off
echo Removing old file...
del ..\..\bdlite.pk3
echo Compiling ACC
c:\doomwork\acc\acc ..\pk3\acs\txt\spaineffect.txt ..\pk3\acs\paineffect.o
c:\doomwork\acc\acc ..\pk3\acs\txt\ssplashes.txt ..\pk3\acs\splashes.o
echo Compressing...
"c:\Program Files\7-Zip\7z.exe" a -tzip ..\..\bdlite.pk3 ..\pk3\*
echo Tidying up...
echo Done
pause