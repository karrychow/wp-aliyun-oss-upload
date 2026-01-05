rm -rf ./aliyun-oss-upload.zip \
&& mkdir -p build/aliyun-oss-upload \
&& rsync -av . build/aliyun-oss-upload \
 --exclude lib/alibabacloud-oss-php-sdk-v2-0.4.0/tests \
 --exclude lib/alibabacloud-oss-php-sdk-v2-0.4.0/sample \
 --exclude .git \
 --exclude build \
 --exclude .cursor \
 --exclude .DS_Store \
 --exclude build.sh \
 --exclude aliyun-oss-upload.zip \
 --exclude .gitattributes \
 --exclude .gitignore \
 --exclude .travis.yml \
 --exclude .coveralls.yml \
 --exclude samples \
 --exclude README-CN.md \
 && cd build \
 && zip -r ../aliyun-oss-upload.zip aliyun-oss-upload \
 && cd .. \
 && rm -rf build
