#!/bin/bash
# SkaleUp BD — dev toolchain lives IN the workspace (~/workspace/tools),
# because this VM's system image can be replaced at any time, wiping
# apt-installed packages. Static PHP + Composer phar survive replacement.
#
# If ~/workspace/tools/php is missing, restore it:
#   mkdir -p ~/workspace/tools && cd ~/workspace/tools
#   curl -sSL -o php.tar.gz https://dl.static-php.dev/static-php-cli/common/php-8.3.27-cli-linux-x86_64.tar.gz
#   tar --no-same-owner -xzf php.tar.gz
#   curl -sSL -o composer.phar https://getcomposer.org/download/latest-stable/composer.phar
#   printf '#!/bin/sh\nexec /home/hatch/workspace/tools/php /home/hatch/workspace/tools/composer.phar "$@"\n' > composer
#   chmod +x composer php
#
# Usage in this project:
#   ~/workspace/tools/php artisan <cmd>
#   ~/workspace/tools/composer <cmd>
#   npm run build   (node/npm are part of the base image)
~/workspace/tools/php -v | head -1
~/workspace/tools/composer --version | head -1
echo "ENV READY"
