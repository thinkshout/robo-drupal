# robo-drupal
Robo commands and tasks for Drupal

Doc pages are at https://thinkshout.github.io/robo-drupal/index.html

Classes are listed at https://thinkshout.github.io/robo-drupal/class_think_shout_1_1_robo_drupal_1_1_tasks.html

# Update information
If you update to the 3.x release, you will need to rerun `robo configure` to set the TS_PROD_BRANCH variable (--prod-branch=main). This allows you to use a branch name for production deployments that is [not the default](https://www.zdnet.com/article/github-to-replace-master-with-alternative-term-to-avoid-slavery-references/). If you do not specify a production branch, it will default to "main".

Once you have set a production branch, you can create a branch with that name from your current production branch, push the new branch up to github, and delete the old branch. In some cases, you may need to change the default branch in github (although that is normally "develop"). You may want to review open pull requests as well, and notify other developers on the project to update their local repositories by pulling down the new branch.
