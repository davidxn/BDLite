@echo off
::::::::::::::
:: Variables
::::::::::::::
SET workdir="..\..\pk3work"
SET finalpk3="..\..\bdlite-plus.pk3"

::::::::::::::
:: Delete anything already in the given folders/file (careful now)
::::::::::::::
echo Removing old folder and file...
rmdir .\%workdir% /s /q
del .\%finalpk3%

::::::::::::::
:: Copy the BDLite data into our work folder
::::::::::::::
md %workdir%
echo Adding BDLite
xcopy ..\pk3\* .\%workdir% /s /y /q

:::::::::::::::
::Edit this part to include your additional mod(s)! It will take the files from the given PK3 folder and overlay them on to the ones from BDLite.
:::::::::::::::
echo Adding extension PK3
xcopy .\extension-example-pk3\* .\%workdir% /s /y /q
:::::::::::::::

:::::::::::::::
::Now compress the combined work folder structure into a PK3 and remove it afterwards.
:::::::::::::::
echo Compressing...
"c:\Program Files\7-Zip\7z.exe" a -tzip %finalpk3% .\%workdir%\*
echo Tidying up...
rmdir .\%workdir% /s /q
echo Done
pause