#!/bin/bash
cd "`dirname $BASH_SOURCE`"
phpdoc -t ./doc -f ./SearchWordsSQL.php -i ./sample
