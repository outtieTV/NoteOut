# OuttieTV's NoteOut

Self-hosted notes web app using php and sqlite3.<br />

Features:<br />
- text notes with markdown support (\<b\>, \<u\>, \<i\>, etc)<br />
- record audio<br />
- record video<br />
- take pictures<br />
- folders and subfolders<br />
<br />

To install:<br />
1. download php 8.4 and extract it to C:\php8.4 so that php.exe is in C:\php8.4\php.exe
2. add php to PATH system variable
3. download sqlite3 ext and uncomment it in php.ini (extension=sqlite3)
4. download NoteOut source code and extract it to C:\servers\noteout or wherever
5. cd to C:\servers\noteout and run php -S 0.0.0.0:80
6. visit http://localhost/db_init.php
7. visit http://localhost and add notes
8. for https, I suggest using nginx reverse proxy or similar

<img width="1924" height="957" alt="image" src="https://github.com/user-attachments/assets/d5024d5e-b542-42f2-bb1c-9417e80f5a6f" />


