# Interactive shells (the dashboard terminal and `yak ssh`) start in the checkout instead of the home directory.
case $- in
  *i*) [ "$PWD" = "$HOME" ] && [ -d /workspace ] && cd /workspace ;;
esac
