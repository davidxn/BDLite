# BDLite
BDLite is a mod for ZDoom with its roots in the popular "Brutal Doom" mod by Sergeant Mark IV. It aims to strip down and reorganize Brutal Doom's maze of classes and features, providing a better base for other level and mod authors to build on top of it.

Anti-features
. No fatalities!
. No "last stand" states for monsters
. No detailed hitbox (head/leg shot) detection
. No dual wielding, each picked-up weapon just gives you more ammo as in original Doom
. No stealing weapons from monsters either
. No alternative fire
. No meat shields
. Or monster interaction (bigger monsters throwing smaller ones)
. No underwater effects
. No map replacements - it’s just a weapon, monster and effect library
. No shotgun testicles

Improvements
. Changed file structure from almost completely flat to the usual PK3 layout
. Completely rewrote and slimmed down the blood system
. Redesigned and standardized how weapons and damage work
. Restructured monster code, especially around death effects
. Removed unused or redundant sprites
. Code style improvements
. Introduced a code style
. Converted incomprehensible "Ready+56" jumps to custom labels
. Removed several hundred lines that looked like TNT1 AAAAAAAAAAAAAAAAA 0
. Excised a ton of commented-out code
. Consolidated classes that were duplicates or so similar as to be redundant
. Changed numbered scripts to named scripts
