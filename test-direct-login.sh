#!/bin/bash

echo "Testing login with direct PHP script..."

# Send a test login request to direct-login.php
curl -v -X POST -d "username=admin&password=password" https://demo.zephyrus.care/direct-login.php

echo -e "\n\nVerifying form submission still works on production..."
curl -v -c cookies.txt https://demo.zephyrus.care/login
curl -v -b cookies.txt -X POST -d "username=admin&password=password" https://demo.zephyrus.care/login

