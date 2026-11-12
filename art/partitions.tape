Output partitions.gif

Set Shell bash
Set FontSize 15
Set Width 1100
Set Height 600
Set Padding 16
Set Theme "Catppuccin Mocha"
Set TypingSpeed 35ms

Hide
Type "export PS1='$ ' && clear"
Enter
Show

Type "php artisan partitions:add transactions --dry-run"
Sleep 400ms
Enter
Sleep 5000ms

Type "clear"
Enter
Type "php artisan partitions:drop transactions --retain=90 --dry-run"
Sleep 400ms
Enter
Sleep 5500ms
