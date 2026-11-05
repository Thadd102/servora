# Subnext Android APK Distribution

This directory holds the downloadable Android APK package (`subnext.apk`).

### Deployment Instructions:
1. When a new release APK is compiled using Capacitor (`npx cap open android` -> Build Signed APK) or Bubblewrap TWA:
2. Place the signed APK file here as:
   `downloads/subnext.apk`
3. The Subnext website dynamically detects `downloads/subnext.apk`. Once the file is present, the **"Direct APK"** download button automatically appears in the install widget on customer phones.
4. If this file is removed or not uploaded yet, the website gracefully falls back to the PWA install option only.

