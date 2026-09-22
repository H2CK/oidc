FROM mcr.microsoft.com/dotnet/sdk:10.0 AS build-env
WORKDIR /OAuch
COPY source/src/OAuch/ ./
RUN dotnet restore
RUN dotnet publish -c Release -o docker-build
RUN dotnet dev-certs https

FROM mcr.microsoft.com/dotnet/aspnet:10.0
WORKDIR /OAuch
COPY --from=build-env /OAuch/docker-build .
COPY --from=build-env /root/.dotnet/corefx/cryptography/x509stores/my/ /root/.dotnet/corefx/cryptography/x509stores/my/
ENV DOTNET_ENVIRONMENT=Docker
COPY nextcloud-test-ca.crt /usr/local/share/ca-certificates/nextcloud-test-ca.crt
RUN update-ca-certificates
EXPOSE 443
ENTRYPOINT ["dotnet", "OAuch.dll"]
