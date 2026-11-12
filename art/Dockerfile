FROM ghcr.io/charmbracelet/vhs

RUN apt-get update \
    && apt-get install -y --no-install-recommends php-cli php-mysql php-mbstring php-xml \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /package/art
