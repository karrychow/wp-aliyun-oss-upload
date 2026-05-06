rm -rf ./lazyaichief-remote-media-storage-aliyun-oss.zip \
&& mkdir -p build/lazyaichief-remote-media-storage-aliyun-oss \
&& rsync -av . build/lazyaichief-remote-media-storage-aliyun-oss \
 --exclude lib/alibabacloud-oss-php-sdk-v2-0.4.0/tests \
 --exclude lib/alibabacloud-oss-php-sdk-v2-0.4.0/sample \
 --exclude .git \
 --exclude build \
 --exclude .cursor \
 --exclude .DS_Store \
 --exclude build.sh \
 --exclude lazyaichief-remote-media-storage-aliyun-oss.zip \
 --exclude .gitattributes \
 --exclude .gitignore \
 --exclude .travis.yml \
 --exclude .coveralls.yml \
 --exclude samples \
 --exclude README-CN.md \
 && cd build \
 && zip -r ../lazyaichief-remote-media-storage-aliyun-oss.zip lazyaichief-remote-media-storage-aliyun-oss \
 && cd .. \
 && rm -rf build
