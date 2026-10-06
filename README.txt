FAMILY COOKBOOK: GODADDY INSTALL
================================

What is in this folder
  index.html   the cookbook (all recipes, search, menus, comments, discussion)
  api.php      the small program that saves and loads comments
  config.php   your settings: the keeper password

Before you upload (2 minutes)
  1. Open config.php in Notepad (or TextEdit in plain-text mode).
  2. Replace CHANGE-THIS-PASSWORD with a password only you know. Keep the
     quote marks. Save the file.
     Until you do this, keeper sign-in is turned off.

Upload to GoDaddy
  1. GoDaddy > My Products > Web Hosting > Manage > cPanel Admin.
  2. Open File Manager, then open the public_html folder.
     (If the cookbook should live at a sub-address such as
     yourdomain.com/cookbook, make a folder called cookbook inside
     public_html and open that instead.)
  3. Move any old index.html or placeholder page out of the way.
  4. Click Upload and add index.html, api.php and config.php.
  5. Visit your domain. Open any recipe and post a test comment.
     The first post creates the database automatically in a folder called
     "data" (you do not need to make it).

Keeper (admin) mode
  Scroll to the bottom of the cookbook and click "Keeper sign-in".
  Enter your password. While signed in you can edit or delete any post.
  Click Sign out when you are done.

Adding and editing recipes (keeper only)
  1. Sign in with "Keeper sign-in" at the bottom of any page.
  2. Open any recipe and click "Edit this recipe", or use "Add a recipe" at
     the bottom of the page or in the menu ("Edit Recipes").
  3. Fill in the form. One ingredient per line, one step per line.
     To make a heading inside the ingredient list, start a line with a
     name and a colon: "Sauce: 2 tablespoons soy sauce".
  4. Click Preview to see how it will look, then Save recipe.
  The "Edit Recipes" page lists everything you changed. Use "Undo my
  changes" to go back to the original, or "Restore" to bring back a
  deleted recipe. Your changes are saved in the "data" folder, so back it
  up with the comments. Replacing index.html later does not erase them.

How people post
  Anyone who visits can comment or start a discussion with just a name.
  Visitors can edit or delete their own posts from the same browser they
  posted from. Spam protection: a hidden trap field, a limit of about
  4 posts a minute per visitor, and no more than 2 links per post.

If something does not work
  - "Comments are unavailable right now": the hosting plan may not have
    PHP or SQLite turned on. In cPanel open "Select PHP Version" (or
    "MultiPHP Manager"), pick PHP 8.0 or newer, and make sure the
    "pdo_sqlite" extension is checked.
  - Keeper sign-in missing: config.php still has the default password.
  - Back up your comments by downloading the "data" folder from
    File Manager now and then.
