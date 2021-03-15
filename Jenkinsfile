pipeline {
    options {
        disableConcurrentBuilds()
    }

    agent any

    environment {
        BUILD_URL = "${BUILD_URL}"
    }

    stages {
        stage ('Checkout') {
          steps {
            checkout scm
          }
        }
        stage('Configurando dependências por pastas') {
          steps {
            sh 'composer install --no-scripts'
          }
        }
        stage('Build') {
            steps {
                echo 'Building project...'
            }
        }
        stage('Test') {
          steps {
            script  {
              try {
                sh './phpunit --log-junit report.xml --coverage-clover clover.xml --coverage-html coverage'
              } catch (err) {
                  echo "Identificado: ${err}"
                  currentBuild.result = 'failure'
              }
            }
          }
        }
        stage('Deploy') {
            steps {
                echo 'Deploy project...'
            }
        }
    }
    post {
        always {
            influxDbPublisher(selectedTarget: 'TestDB', customData: assignURL(BUILD_URL))
        }
    }
}

def assignURL(build_url) {
    def buildURL = [:]
    buildURL['url'] = build_url
    return buildURL
}
