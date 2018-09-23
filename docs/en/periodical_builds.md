Periodical builds
=================

**Periodical builds works only with [worker](workers/worker.md)! Cronjob build task `php-censor:run-builds` isn't 
supported**

Periodical builds config example (`<php-censor-path>/app/periodical.yml`):

```yaml
projects:
    1:                    # Project id
        branches:         # Branch list for periodical build
            - master
            - release-1.0
            - release-2.0
        interval: P1W     # Interval to build project if no other builds (from webhook etc.).Used format of PHP DateInterval class. See: http://php.net/manual/ru/dateinterval.construct.php
    12:                   # Another project id
        branches:
            - master
        interval: PT12H
```
