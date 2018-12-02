@echo off
::::::::::::::
:: This script takes a BDLite PK3 and adds the files from a project folder on to it, producting the final PK3.
::::::::::::::

::::::::::::::
:: Variables
::::::::::::::
SET ziptool=c:\Program Files\7-Zip\7z.exe
SET finalpk3=c:\doomwork\bdlite\bdlite-plus.pk3
SET bdlitepk3=c:\doomwork\bdlite\bdlite.pk3
SET projectfolder=.\extension-example-pk3

::::::::::::::
:: Delete the final file if it exists already (careful now)
::::::::::::::
echo Removing old folder and file...
del "%finalpk3%"

::::::::::::::
:: Copy the BDLite PK3 into our final location with the 
::::::::::::::
echo Copying BDLite
xcopy "%bdlitepk3%" "%finalpk3%*" /y

:::::::::::::::
::Edit this part to include your additional mod(s)! It will take the files from the given PK3 folder and overlay them on to the base BDLite PK3.
:::::::::::::::
echo Adding files from project
"%ziptool%" a -tzip "%finalpk3%" "%projectfolder%\*"
:::::::::::::::

echo Created %finalpk3%
pause