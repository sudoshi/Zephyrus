#!/bin/bash

# Test script for the new session-based authentication system

echo "Testing Session-Based Authentication"
echo "==================================="
echo

# Check if the application is running
echo "1. Checking if the application is running..."
if curl -s -o /dev/null -w "%{http_code}" http://demo.zephyrus.care/ | grep -q "200\|302"; then
    echo "✅ Application is running"
else
    echo "❌ Application is not running. Please start the application first."
    exit 1
fi
echo

# Test login page
echo "2. Testing login page..."
if curl -s -o /dev/null -w "%{http_code}" http://demo.zephyrus.care/login | grep -q "200"; then
    echo "✅ Login page is accessible"
else
    echo "❌ Login page is not accessible"
    exit 1
fi
echo

# Test login with curl
echo "3. Testing login with curl..."
echo "This will attempt to log in and follow redirects to see if authentication works."
echo "Note: This is a basic test. Manual testing in the browser is still recommended."

# Store cookies in a temporary file
COOKIE_JAR=$(mktemp)

# Attempt to log in
LOGIN_RESPONSE=$(curl -s -c $COOKIE_JAR -b $COOKIE_JAR -L -X POST \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -d "username=admin&password=password" \
    http://demo.zephyrus.care/login)

# Check if we were redirected to the dashboard
if echo "$LOGIN_RESPONSE" | grep -q "dashboard"; then
    echo "✅ Login successful, redirected to dashboard"
else
    echo "❌ Login failed or not redirected to dashboard"
    echo "Response contains:"
    echo "$LOGIN_RESPONSE" | head -n 20
fi
echo

# Test accessing a protected route
echo "4. Testing access to a protected route..."
PROTECTED_RESPONSE=$(curl -s -b $COOKIE_JAR -L http://demo.zephyrus.care/dashboard)

if echo "$PROTECTED_RESPONSE" | grep -q "dashboard"; then
    echo "✅ Successfully accessed protected route"
else
    echo "❌ Failed to access protected route"
    echo "Response contains:"
    echo "$PROTECTED_RESPONSE" | head -n 20
fi
echo

# Test logout
echo "5. Testing logout..."
LOGOUT_RESPONSE=$(curl -s -c $COOKIE_JAR -b $COOKIE_JAR -L -X POST http://demo.zephyrus.care/logout)

# Try accessing a protected route after logout
AFTER_LOGOUT_RESPONSE=$(curl -s -b $COOKIE_JAR -L http://demo.zephyrus.care/dashboard)

if echo "$AFTER_LOGOUT_RESPONSE" | grep -q "login"; then
    echo "✅ Logout successful, redirected to login page"
else
    echo "❌ Logout failed or not redirected to login page"
    echo "Response contains:"
    echo "$AFTER_LOGOUT_RESPONSE" | head -n 20
fi
echo

# Clean up
rm $COOKIE_JAR

echo "Testing completed."
echo "For more thorough testing, please manually test the application in a browser."
echo "Check the docs/session-auth.md file for troubleshooting tips if you encounter issues."
