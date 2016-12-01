# github-commit-alert

This script watches designated repositories on github.com for new commits,
and emails the addresses specified in config.yml when commits appear.

The closest option github currently offers through the web UI, at least
for repositories you do not own, seems to send you *everything* on a 
repository, making for a very noisy inbox.

## Requirements and Setup
 - This php script requires php 5.6+
 - There are dependencies on external php libraries. To download them
   you'll need to run [composer install](https://getcomposer.org/).
 - Copy config.yml.dist to config.yml and edit email settings as desired.
 
## Usage
 - Add the repository `EnterpriseQualityCoding/FizzBuzzEnterpriseEdition`
   to those watched:  
   `./bin/github-commit-alert.php EnterpriseQualityCoding/FizzBuzzEnterpriseEdition`
 - Recheck all watched repositories:  
   `./bin/github-commit-alert.php`  
   (no options or operands.)  
   You can run this script as often as you like, ETag caching should
   prevent you from hitting github API throttling limits.
 - Additional functionality as documented in internal help:  
   `./bin/github-commit-alert.php -h`
   
## License
MIT  
&copy; 2016 Michael Baynton